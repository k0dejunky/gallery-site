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

        $this->view('home', [
            'galleries'      => $galleries,
            'covers'         => $covers,
            'categories'     => $categories,
            'categoryCounts' => $countsById,
            'mediaCounts'    => Photo::siteCounts(),
            'title'          => 'Home',
            'canonicalUrl'   => absolute_url('/'),
            'metaDescription' => 'The private collection of ' . config('app.site_name') . '. Join for full access to original photos, videos and live streams.',
            'ogImage'        => $ogImage,
        ]);
    }
}