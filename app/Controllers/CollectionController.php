<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Collection;
use App\Models\Gallery;

/**
 * Member collections (gallery playlists): create/rename/delete collections and
 * add/remove galleries. All actions require login and enforce ownership.
 */
class CollectionController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
    }

    /** List the current user's collections. */
    public function index(): void
    {
        Auth::requireLogin();
        $userId = (int) $_SESSION['user_id'];

        $this->view('collections/index', [
            'collections' => Collection::forUser($userId),
        ]);
    }

    /** Show one collection's galleries. */
    public function show(int $id): void
    {
        Auth::requireLogin();
        $userId = (int) $_SESSION['user_id'];

        if (!Collection::owns($id, $userId)) {
            $this->notFound();
            return;
        }

        $collection = Collection::find($id);
        $galleries  = Collection::galleries($id, $userId);
        $videos     = Collection::videos($id, $userId);

        $this->view('collections/show', [
            'collection' => $collection,
            'galleries'  => $galleries,
            'videos'     => $videos,
            'firstVideoId' => $videos !== [] ? (int) $videos[0]['id'] : 0,
            'favoriteGalleryIds' => Gallery::favoriteIds($userId, array_map('intval', array_column($galleries, 'id'))),
        ]);
    }

    /** Create a collection. */
    public function create(): void
    {
        Auth::requireLogin();
        $name = trim((string) $this->request->input('name'));

        if ($name === '') {
            $this->flash('error', 'Give the collection a name.');
        } else {
            $id = Collection::create((int) $_SESSION['user_id'], $name);
            $this->flash('success', 'Collection created.');
            $this->redirect('/collections/' . $id);
            return;
        }

        $this->redirect('/collections');
    }

    /** Rename a collection. */
    public function rename(int $id): void
    {
        Auth::requireLogin();
        $name = trim((string) $this->request->input('name'));

        if (Collection::rename($id, (int) $_SESSION['user_id'], $name)) {
            $this->flash('success', 'Collection renamed.');
        } else {
            $this->flash('error', 'Could not rename that collection.');
        }
        $this->redirect('/collections/' . $id);
    }

    /** Delete a collection. */
    public function delete(int $id): void
    {
        Auth::requireLogin();

        if (Collection::delete($id, (int) $_SESSION['user_id'])) {
            $this->flash('success', 'Collection deleted.');
        } else {
            $this->flash('error', 'Could not delete that collection.');
        }
        $this->redirect('/collections');
    }

    /** Add a gallery to a collection (from the gallery page). */
    public function addGallery(int $id): void
    {
        Auth::requireLogin();
        $galleryId = (int) $this->request->post('gallery_id', 0);

        if (Collection::addGallery($id, (int) $_SESSION['user_id'], $galleryId)) {
            $this->flash('success', 'Added to collection.');
        } else {
            $this->flash('error', 'Already in that collection, or it was not found.');
        }
        $this->redirect('/galleries/' . $galleryId);
    }

    /** Remove a gallery from a collection. */
    public function removeGallery(int $id, int $galleryId): void
    {
        Auth::requireLogin();

        Collection::removeGallery($id, (int) $_SESSION['user_id'], $galleryId);
        $this->flash('success', 'Removed from collection.');
        $this->redirect('/collections/' . $id);
    }

    /** Add an individual video to a collection (from the video player). */
    public function addPhoto(int $id): void
    {
        Auth::requireLogin();
        $photoId = (int) $this->request->post('photo_id', 0);

        if (Collection::addPhoto($id, (int) $_SESSION['user_id'], $photoId)) {
            $this->flash('success', 'Added video to collection.');
        } else {
            $this->flash('error', 'That video is already in the collection, or it was not found.');
        }
        $this->redirect('/videos/' . $photoId);
    }

    /** Remove an individual video from a collection. */
    public function removePhoto(int $id, int $photoId): void
    {
        Auth::requireLogin();

        Collection::removePhoto($id, (int) $_SESSION['user_id'], $photoId);
        $this->flash('success', 'Removed from collection.');
        $this->redirect('/collections/' . $id);
    }
}