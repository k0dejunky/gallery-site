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
        $photo = Photo::find($id);

        if ($photo === null || is_video($photo['filename']) !== $requireVideo) {
            $this->notFound();
            return;
        }

        $galleryId = Photo::firstGalleryId($id);
        $gallery   = $galleryId !== null ? Gallery::find($galleryId) : null;
        $user      = Auth::user();

        // Media sitting in a published, non-secret gallery is indexable: it
        // renders a blurred preview for guests and search engines, while the
        // full-resolution view stays behind login + membership level.
        $isPublic = Photo::hasPublicGallery($id);

        if ($user === null) {
            if (!$isPublic) {
                $this->notFound();
                return;
            }
            $canViewFull = false;
        } else {
            if (!Photo::userCanView($id, (int) $user['id'])) {
                $this->notFound();
                return;
            }
            if (\App\Core\Auth::isSuperAdmin()) {
                $canViewFull = true;
            } elseif (!$isPublic) {
                // Secret / allow-listed gallery: access is by the allow-list,
                // the level gate does not apply.
                $canViewFull = true;
            } else {
                $canViewFull = Auth::effectiveLevel() >= Photo::minimumGalleryLevel($id);
            }
        }

        if ($user !== null && $canViewFull) {
            Photo::recordView($id, (int) $user['id']);
        }

        $currentIndex = 0;
        $mediaCount   = 1;
        $prev = $next = $prevGallery = $nextGallery = null;

        // Neighbour navigation, playlists and collections only matter for the
        // full view; the guest preview is a single blurred frame.
        $playlist      = [];
        $playlistName  = null;
        $playlistQuery = '';
        $playlistId    = 0;
        $collections   = [];

        if ($canViewFull && $user !== null) {
            [$currentIndex, $mediaCount, $prev, $next] = $galleryId !== null
                ? Gallery::neighborsAndIndex($galleryId, $id)
                : [0, 1, null, null];

            if ($galleryId !== null) {
                $maxLevel    = Auth::effectiveLevel();
                $nextGallery = Gallery::neighborVisible((int) $galleryId, 'next', $maxLevel);
                $prevGallery = Gallery::neighborVisible((int) $galleryId, 'prev', $maxLevel);
            }

            $userId = (int) $user['id'];
            $collections = \App\Models\Collection::forUser($userId);

            $playlistId = (int) $this->request->query('playlist', 0);
            if ($playlistId > 0 && \App\Models\Collection::owns($playlistId, $userId)) {
                $collection   = \App\Models\Collection::find($playlistId);
                $playlistName = $collection !== false ? (string) $collection['name'] : 'Collection';
                $playlist     = \App\Models\Collection::media($playlistId, $userId);

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

        $inPlaylist = $playlistId > 0 && $playlist !== [];
        $view = $inPlaylist ? 'video/player' : ($requireVideo ? 'video/player' : 'gallery/image_full');

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
            'canViewFull'   => $canViewFull,
            'comments'      => \App\Models\Comment::forEntity(\App\Models\Comment::TYPE_PHOTO, $id),
            'commentCount'  => \App\Models\Comment::countFor(\App\Models\Comment::TYPE_PHOTO, $id),
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
