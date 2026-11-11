<?php

namespace App\Controllers;

use App\Core\Auth;
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

        // The same Type / Content / Sort filters the catalogue and the
        // category pages carry: a tag is a browse surface, not a dead end.
        $type    = (string) $this->request->query('type', '');
        $content = (string) $this->request->query('content', '');
        $sort    = (string) $this->request->query('sort', '');
        $filters = [
            // include_gated keeps a paid set visible (blurred) rather than
            // hiding it: the card renders the lock.
            'max_level'     => PHP_INT_MAX,
            'include_gated' => true,
            'type'          => in_array($type, ['images', 'videos'], true) ? $type : '',
            'content'       => in_array($content, ['sfw', 'nsfw'], true) ? $content : '',
            'sort'          => in_array($sort, ['views', 'title'], true) ? $sort : '',
        ];

        $galleries = Gallery::byTag($slug, $limit, ($page - 1) * $limit, $filters);

        $next = Gallery::byTag($slug, $limit, $page * $limit, $filters) !== [];

        // The per-user state of a gallery card (seen, favourited) belongs to
        // the member, so the tag page has to hand it to the view like the
        // listing and the category pages do.
        $user = Auth::user();
        $ids  = array_map('intval', array_column($galleries, 'id'));

        $this->view('gallery/tag', [
            'title'     => 'Tag: ' . $tag['name'],
            'tag'       => $tag,
            'galleries' => $galleries,
            'page'      => $page,
            'limit'     => $limit,
            'hasNext'   => $next,
            'type'      => $filters['type'],
            'contentFilter' => $filters['content'],
            'sort'      => $filters['sort'],
            'currentUser' => $user,
            'hasActive' => $user !== null ? Auth::hasActiveSubscription() : false,
            'viewedIds' => $user !== null ? Gallery::viewedByIds((int) $user['id'], $ids) : [],
            'favoriteGalleryIds' => ($user !== null && Auth::hasActiveSubscription())
                ? Gallery::favoriteIds((int) $user['id'], $ids) : [],
            'cardCovers' => Gallery::preloadCards($galleries),
        ]);
    }
}