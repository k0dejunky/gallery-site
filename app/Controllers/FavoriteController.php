<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Category;
use App\Models\FavoriteCategory;
use App\Models\Plan;

class FavoriteController extends Controller
{
    /**
     * Favouriting is a logged-in user action.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requireLogin();
    }

    /**
     * Add or remove a category as a favourite for the current user.
     */
    public function toggle(int $categoryId): void
    {
        $category = Category::find($categoryId);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $userId = (int) $_SESSION['user_id'];

        if (FavoriteCategory::isFavorite($userId, $categoryId)) {
            FavoriteCategory::remove($userId, $categoryId);
            $this->flash('success', 'Removed "' . $category['name'] . '" from your favorites.');
        } else {
            Auth::requireMembershipLevel(
                Plan::SILVER_LEVEL,
                'Selecting favorite categories requires at least a Silver level membership.'
            );
            FavoriteCategory::add($userId, $categoryId);
            $this->flash('success', 'Added "' . $category['name'] . '" to your favorites.');
        }

        $this->redirect('/galleries');
    }
}
