<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Photo;

/**
 * Guest landing page. Members are redirected to their role-appropriate home
 * (the gallery dashboard) so the interesting pages never show the marketing
 * splash twice; logged-out visitors get the hero + latest gallery grid used
 * to drive signups.
 */
class HomeController extends Controller
{
    public function index(): void
    {
        if (Auth::check()) {
            $this->redirect(Auth::homePath());
        }

        // The latest published, non-secret galleries across all levels: locked
        // content still renders its blurred teaser cover on the card, so the
        // grid stays appetising without leaking any pixels.
        $galleries = Database::run(
            'SELECT g.*, '
            . '(SELECT COUNT(*) FROM gallery_photo gp WHERE gp.gallery_id = g.id) AS photo_count, '
            . Gallery::videoCountSql() . '
             FROM galleries g
             WHERE g.is_secret = 0 AND ' . Gallery::publishedVisibleSql('g') . '
             ORDER BY g.created_at DESC
             LIMIT 12',
            []
        )->fetchAll();

        $covers = Gallery::firstPhotos(array_map('intval', array_column($galleries, 'id')));

        $categories = Category::all();
        $countsById = [];
        if ($categories !== []) {
            $catRows = Database::run(
                'SELECT c.id, COUNT(gc.gallery_id) AS n
                 FROM categories c
                 LEFT JOIN gallery_category gc ON gc.category_id = c.id
                 LEFT JOIN galleries g ON g.id = gc.gallery_id AND g.is_secret = 0 AND ' . Gallery::publishedVisibleSql('g') . '
                 GROUP BY c.id',
                []
            )->fetchAll();
            foreach ($catRows as $row) {
                $countsById[(int) $row['id']] = (int) $row['n'];
            }
            $categories = array_values(array_filter(
                $categories,
                static fn (array $cat): bool => ($countsById[(int) $cat['id']] ?? 0) > 0
            ));
        }

        $ogImage = '';
        if ($galleries !== []) {
            foreach ($galleries as $g) {
                $cover = $covers[(int) $g['id']] ?? null;
                if ($cover && !empty($cover['filename'])) {
                    $ogImage = absolute_url(file_url((string) $cover['filename'], 'thumb'));
                    break;
                }
            }
        }

$featured  = Gallery::featured(6);
        $trending  = Gallery::trending(7, 6);

        // Trending shares the layout with featured; drop any gallery already
        // shown as featured so the two strips don't repeat cards.
        $featuredIds = array_map('intval', array_column($featured, 'id'));
        $trending = array_values(array_filter(
            $trending,
            static fn (array $g): bool => !in_array((int) $g['id'], $featuredIds, true)
        ));

        $this->view('home', [
            'galleries'      => $galleries,
            'featured'       => $featured,
            'trending'       => $trending,
            'tags'           => \App\Models\Tag::withCounts(),
            'covers'         => $covers,
            'categories'     => $categories,
            'categoryCounts' => $countsById,
            'mediaCounts'    => Photo::siteCounts(),
            'title'          => 'Home',
            'canonicalUrl'   => absolute_url('/'),
            'metaDescription' => 'The private collection of ' . config('app.site_name') . '. Join now for full access to exclusive photo and video galleries, live streams and more.',
            'ogImage'        => $ogImage,
        ]);
    }
}