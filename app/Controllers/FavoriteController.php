<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Category;
use App\Models\FavoriteCategory;
use App\Models\Gallery;
use App\Models\Plan;
use App\Models\SavedSearch;

class FavoriteController extends Controller
{
    /**
     * No constructor auth — individual actions gate themselves so the site
     * editor can preview public pages with ?se=user.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
    }

    /**
     * Add or remove a category as a favourite for the current user.
     */
    public function toggle(int $categoryId): void
    {
        Auth::requireLogin();
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

    public function index(): void
    {
        $siteEditorPreview = $this->request->query('se', '') === 'user';
        if (!$siteEditorPreview) {
            Auth::requireSubscription();
        }
        $userId = $siteEditorPreview ? 0 : (int) Auth::user()['id'];
        $galleries = $siteEditorPreview ? [] : Gallery::favoriteGalleries($userId, 100);
        $ids = array_map('intval', array_column($galleries, 'id'));

        $recentlyViewed = $siteEditorPreview ? [] : Gallery::recentlyViewed($userId, 8);
        $rvIds = array_map('intval', array_column($recentlyViewed, 'id'));

        $this->view('favorites/index', [
            'title' => 'Favorites',
            'favoriteCategories' => $siteEditorPreview ? [] : FavoriteCategory::forUser($userId),
            'favoriteGalleries' => $galleries,
            'savedSearches' => $siteEditorPreview ? [] : SavedSearch::forUser($userId),
            'recentlyViewed' => $recentlyViewed,
            'cardCovers' => $siteEditorPreview ? [] : [
                'covers' => Gallery::firstPhotos(array_unique(array_merge($ids, $rvIds))),
                'categories' => Gallery::categoriesBulk(array_unique(array_merge($ids, $rvIds))),
            ],
            'currentUser' => $siteEditorPreview ? ['id' => 0] : Auth::user(),
            'hasActive' => true,
            'viewedIds' => $siteEditorPreview ? [] : Gallery::viewedByIds($userId, array_unique(array_merge($ids, $rvIds))),
            'siteEditorPreview' => $siteEditorPreview,
        ]);
    }

    /**
     * Add or remove a gallery as a favourite for the current user.
     */
    public function toggleGallery(int $galleryId): void
    {
        Auth::requireLogin();
        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            $this->notFound();
            return;
        }

        Auth::requireMembershipLevel(
            Plan::SILVER_LEVEL,
            'Selecting favorite galleries requires at least a Silver level membership.'
        );

        $favorited = Gallery::toggleFavorite((int) $_SESSION['user_id'], $galleryId);
        $this->flash('success', ($favorited ? 'Added "' : 'Removed "') . $gallery['title'] . '" ' . ($favorited ? 'to' : 'from') . ' your favorite galleries.');

        $returnTo = (string) $this->request->query('return_to', $this->request->input('return_to', ''));
        if (!preg_match('#^/(?!/)[^\\r\\n]*$#', $returnTo) || parse_url($returnTo, PHP_URL_HOST) !== null) {
            $returnTo = '/galleries';
        }

        $this->redirect($returnTo);
    }
}
