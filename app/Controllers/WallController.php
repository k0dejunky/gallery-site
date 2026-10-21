<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Comment;
use App\Models\Gallery;
use App\Models\Notification;
use App\Models\WallPost;

/**
 * The member Wall: the site landing page for logged-in users. Shows the
 * signed-in member's notifications rendered as feed-style gallery posts
 * (with membership-aware image previews) followed by creator wall posts,
 * newest first (pinned on top), each with its comment thread. Gallery-linked
 * wall posts (published by the auto poster per recommended gallery) render
 * the same membership-gated preview grid as notifications. Posting is
 * creator-only (admin Engagement page); members read, get notified and
 * comment.
 */
class WallController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $user  = Auth::user();

        // The wall is a feed: it is paged so the older posts are reachable
        // instead of stopping at the newest 30.
        $perPage = 20;
        $total   = WallPost::countPublished();
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($pages, (int) $this->request->query('page', 1)));

        $posts = WallPost::allPublished($perPage, ($page - 1) * $perPage);

        $galleryIds = [];
        foreach ($posts as $post) {
            if ((int) ($post['gallery_id'] ?? 0) > 0) {
                $galleryIds[(int) $post['gallery_id']] = (int) $post['gallery_id'];
            }
        }

        $previews = $galleryIds === []
            ? []
            : $this->buildGalleryPreviews($galleryIds, (int) $user['id']);

        foreach ($posts as &$post) {
            // The view counts the comments from the rows it already fetches
            // (Comment::forEntity), so a COUNT per post here was 20 wasted
            // queries on every wall view - and the value was never read.
            $gid = (int) ($post['gallery_id'] ?? 0);
            if ($gid > 0 && isset($previews[$gid])) {
                $post['preview'] = $previews[$gid];
            }
        }
        unset($post);

        $notifications = Notification::forUser((int) $user['id'], 30);

        foreach ($notifications as &$n) {
            if ($n['read_at'] === null) {
                $unreadIds[] = (int) $n['id'];
            }
        }
        unset($n);

        $notifications = $this->decorateNotifications($notifications, (int) $user['id']);

        // The wall SHOWS the inbox, so opening it is reading it. The nav
        // badge used to stay lit until the member clicked every single
        // notification - it now follows the attention.
        if ($unreadIds ?? []) {
            Notification::markManyRead($unreadIds, (int) $user['id']);
        }

        $this->view('wall', [
            'posts'         => $posts,
            // The comments of every post in ONE query. The view used to
            // call forEntity() once per post - 20 queries for a 20-post
            // feed.
            'postComments'  => \App\Models\Comment::forEntities(
                \App\Models\Comment::TYPE_WALL,
                array_map(static fn (array $p): int => (int) $p['id'], $posts)
            ),
            'notifications' => $notifications,
            'unreadIds'     => $unreadIds ?? [],
            'sidebarNav'    => true,
            'paginator'     => ['page' => $page, 'pages' => $pages, 'total' => $total],
            'baseUrl'       => url('/wall'),
        ]);
    }

    /**
     * Membership-aware gallery preview for a set of gallery ids: the viewer
     * sees real thumbnails only when their level reaches each gallery's
     * min_level (admins and PPV-unlocked owners always do); otherwise they
     * get the blurred teaser (the wall view renders the blur size + lock).
     */
    private function buildGalleryPreviews(array $galleryIds, int $userId): array
    {
        $galleries = Gallery::findMany($galleryIds);
        $previews  = Gallery::previewsBulk($galleryIds, 3);
        $counts    = Gallery::photoCountsBulk($galleryIds);

        $effectiveLevel = Auth::effectiveLevel();
        // One query for all the galleries, not one per gallery.
        $unlocked = \App\Models\Purchase::unlockedGalleryIds($userId, $galleryIds);

        $result = [];
        foreach ($galleryIds as $galleryId) {
            $gallery = $galleries[$galleryId] ?? null;
            if ($gallery === null) {
                continue;
            }

            $minLevel = (int) ($gallery['min_level'] ?? 0);

            $result[$galleryId] = [
                'gallery_id'  => $galleryId,
                'title'       => (string) $gallery['title'],
                'description' => (string) ($gallery['description'] ?? ''),
                'can_view'    => $effectiveLevel >= PHP_INT_MAX
                    || $effectiveLevel >= $minLevel
                    || isset($unlocked[$galleryId]),
                'count'       => (int) ($counts[$galleryId] ?? count($previews[$galleryId] ?? [])),
                'photos'      => array_map(static function (array $photo): array {
                    return [
                        'filename' => (string) $photo['filename'],
                        'caption'  => (string) ($photo['caption'] ?? ''),
                        'is_video' => !empty($photo['is_video']) || is_video((string) $photo['filename']),
                    ];
                }, $previews[$galleryId] ?? []),
            ];
        }

        return $result;
    }

    /**
     * Turn gallery-link notifications into feed posts with a membership-aware
     * media preview (see buildGalleryPreviews). Non-gallery notifications
     * pass through untouched.
     */
    private function decorateNotifications(array $notifications, int $userId): array
    {
        if ($notifications === []) {
            return [];
        }

        $galleryIds = [];
        foreach ($notifications as $n) {
            if (preg_match('#^/galleries/(\d+)#', (string) ($n['url'] ?? ''), $m)) {
                $galleryIds[(int) $m[1]] = (int) $m[1];
            }
        }

        $previews = $galleryIds === [] ? [] : $this->buildGalleryPreviews($galleryIds, $userId);

        foreach ($notifications as &$n) {
            $url = (string) ($n['url'] ?? '');
            if (!preg_match('#^/galleries/(\d+)#', $url, $m)) {
                continue;
            }

            $galleryId = (int) $m[1];
            if (isset($previews[$galleryId])) {
                $n['preview'] = $previews[$galleryId];
            }
        }
        unset($n);

        return $notifications;
    }
}