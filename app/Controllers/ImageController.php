<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Gallery;
use App\Models\Photo;

class ImageController extends Controller
{
    /**
     * Full-size image page rendered inside the site template.
     */
    public function show(int $id): void
    {
        $this->showMedia($id, false);
    }

    /**
     * Shared media viewer for both images and videos: verifies the media type
     * matches, records the view, loads neighbours and renders the appropriate
     * view template.
     */
    protected function showMedia(int $id, bool $requireVideo): void
    {
        Auth::requireLogin();

        $photo = Photo::find($id);

        if ($photo === null || is_video($photo['filename']) !== $requireVideo) {
            $this->notFound();
            return;
        }

        $galleryId = Photo::firstGalleryId($id);
        $gallery   = $galleryId !== null ? Gallery::find($galleryId) : null;
        $user      = Auth::user();

        if ($user === null || !Photo::userCanView($id, (int) $user['id'])) {
            $this->notFound();
            return;
        }

        if (Photo::hasPublicGallery($id)) {
            Auth::requireGalleryLevel(
                Photo::minimumGalleryLevel($id),
                'This media needs a ' . \App\Models\Subscription::levelLabel(Photo::minimumGalleryLevel($id)) . ' membership to view.'
            );
        }

        if ($user !== null) {
            Photo::recordView($id, (int) $user['id']);
        }

        [$currentIndex, $mediaCount, $prev, $next] = $galleryId !== null
            ? Gallery::neighborsAndIndex($galleryId, $id)
            : [0, 1, null, null];

        // Neighbour galleries for the "next gallery" step at the end of a
        // gallery's items (skips galleries the member cannot view).
        $prevGallery = $nextGallery = null;
        if ($galleryId !== null) {
            $maxLevel = Auth::effectiveLevel();
            $nextGallery = Gallery::neighborVisible((int) $galleryId, 'next', $maxLevel);
            $prevGallery = Gallery::neighborVisible((int) $galleryId, 'prev', $maxLevel);
        }

        // Optional collection-as-playlist: ?playlist={collectionId} plays the
        // collection's individual videos, and prev/next move within it.
        $playlist      = [];
        $playlistName  = null;
        $playlistQuery = '';
        $playlistId    = 0;
        $collections   = [];
        $userId = $user !== null ? (int) $user['id'] : 0;

        if ($userId > 0) {
            $collections = \App\Models\Collection::forUser($userId);

            $playlistId = (int) $this->request->query('playlist', 0);
            if ($playlistId > 0 && \App\Models\Collection::owns($playlistId, $userId)) {
                $collection   = \App\Models\Collection::find($playlistId);
                $playlistName = $collection !== false ? (string) $collection['name'] : 'Collection';
                $playlist     = \App\Models\Collection::videos($playlistId, $userId);

                if ($playlist !== []) {
                    $playlistQuery = '&playlist=' . $playlistId;
                    $keys = array_column($playlist, 'id');
                    $pos  = array_search($id, array_map('intval', $keys), true);
                    $pos  = $pos === false ? 0 : $pos;
                    $prev = $pos > 0 ? $playlist[$pos - 1] : null;
                    $next = $pos < count($playlist) - 1 ? $playlist[$pos + 1] : null;
                    $currentIndex = $pos;
                    $mediaCount   = count($playlist);
                }
            }
        }

        $returnTo = $this->safeReturnTo($this->request->query('return_to', ''))
            ?? ($galleryId !== null ? url('/galleries/' . $galleryId) : url('/galleries'));

        $view = $requireVideo ? 'video/player' : 'gallery/image_full';

        $this->view($view, [
            'photo'   => $photo,
            'gallery' => $gallery,
            'prev'    => $prev,
            'next'    => $next,
            'mediaCount' => $mediaCount,
            'currentIndex' => $currentIndex,
            'returnTo' => $returnTo,
            'prevGallery' => $prevGallery,
            'nextGallery' => $nextGallery,
            'collections'   => $collections,
            'playlist'      => $playlist,
            'playlistName'  => $playlistName,
            'playlistQuery' => $playlistQuery,
            'playlistId'    => $playlistId,
        ]);
    }

    /** Accept only relative URLs belonging to this installation. */
    private function safeReturnTo($value): ?string
    {
        if (!is_string($value) || $value === '' || strpos($value, '//') === 0) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || empty($parts['path'])) {
            return null;
        }

        $base = rtrim((string) config('app.base_path'), '/');
        if ($base !== '' && strpos($parts['path'], $base . '/') !== 0 && $parts['path'] !== $base) {
            return null;
        }

        return $value;
    }
}
