<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Gallery;
use App\Models\GalleryRating;

/**
 * One star rating (1-5) per member per gallery.
 */
class GalleryRatingController extends Controller
{
    public function store(int $id): void
    {
        Auth::requireLogin();

        $gallery = Gallery::find($id);
        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $stars = (int) $this->request->post('rating', '0');
        if ($stars < 1 || $stars > 5) {
            $this->flash('error', 'Please choose a rating between 1 and 5 stars.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        GalleryRating::set((int) Auth::user()['id'], $id, $stars);

        $this->flash('success', 'Thanks — your ' . $stars . '-star rating was saved.');
        $this->redirect('/galleries/' . $id);
    }
}