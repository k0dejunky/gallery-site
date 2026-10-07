<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Gallery;
use App\Models\Tag;

/**
 * Public tag filter page: /galleries/tag/{slug} lists every visible gallery
 * carrying that tag.
 */
class TagController extends Controller
{
    public function index(string $slug): void
    {
        $tag = Tag::findBySlug($slug);
        if ($tag === null) {
            $this->notFound();
            return;
        }

        $page  = max(1, (int) $this->request->query('page', '1'));
        $limit = 24;
        $galleries = Gallery::byTag($slug, $limit, ($page - 1) * $limit);

        $next = Gallery::byTag($slug, $limit, $page * $limit) !== [];

        $this->view('gallery/tag', [
            'title'     => 'Tag: ' . $tag['name'],
            'tag'       => $tag,
            'galleries' => $galleries,
            'page'      => $page,
            'limit'     => $limit,
            'hasNext'   => $next,
        ]);
    }
}