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

        Auth::requireGalleryLevel(
            Photo::minimumGalleryLevel($id),
            'A membership is required to view that media.'
        );

        $user = Auth::user();

        if ($user !== null) {
            Photo::recordView($id, (int) $user['id']);
        }

        [$prev, $next] = $galleryId !== null
            ? Photo::galleryNeighbors($galleryId, $id)
            : [null, null];

        $mediaItems = $galleryId !== null ? Gallery::photos($galleryId) : [$photo];
        $currentIndex = 0;
        foreach ($mediaItems as $index => $item) {
            if ((int) $item['id'] === $id) {
                $currentIndex = $index;
                break;
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
            'mediaItems' => $mediaItems,
            'currentIndex' => $currentIndex,
            'returnTo' => $returnTo,
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
