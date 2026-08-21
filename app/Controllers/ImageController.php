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
        Auth::requireSubscription();

        $photo = Photo::find($id);

        if ($photo === null || is_video($photo['filename']) !== $requireVideo) {
            $this->notFound();
            return;
        }

        $user = Auth::user();

        if ($user !== null) {
            Photo::recordView($id, (int) $user['id']);
        }

        $galleryId = Photo::firstGalleryId($id);
        $gallery   = $galleryId !== null ? Gallery::find($galleryId) : null;

        [$prev, $next] = $galleryId !== null
            ? Photo::galleryNeighbors($galleryId, $id)
            : [null, null];

        $view = $requireVideo ? 'video/player' : 'gallery/image_full';

        $this->view($view, [
            'photo'   => $photo,
            'gallery' => $gallery,
            'prev'    => $prev,
            'next'    => $next,
        ]);
    }
}
