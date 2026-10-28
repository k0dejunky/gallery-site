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

        // The member's own notifications. They are the newest activity by
        // nature, so they are merged into the FIRST page of the timeline
        // only - on the older pages they would repeat.
        $notifications = $page === 1 ? Notification::forUser((int) $user['id'], 30) : [];

        foreach ($notifications as &$n) {
            if ($n['read_at'] === null) {
                $unreadIds[] = (int) $n['id'];
            }
        }
        unset($n);

        $notifications = $this->decorateNotifications($notifications, (int) $user['id']);

        // The comments of every post in ONE query. The view used to call
        // forEntity() once per post - 20 queries for a 20-post feed.
        $postComments = \App\Models\Comment::forEntities(
            \App\Models\Comment::TYPE_WALL,
            array_map(static fn (array $p): int => (int) $p['id'], $posts)
        );

        // ONE timeline: the studio's updates and the member's notifications,
        // newest first, each with its own type badge. The wall used to be
        // two separate blocks - a collapsible inbox above a post list - and
        // a member with 30 notifications had to scroll past all of them to
        // reach the studio's news.
        $items = $this->buildFeed($posts, $notifications, $postComments);

        // The wall SHOWS the inbox, so opening it is reading it. The nav
        // badge used to stay lit until the member clicked every single
        // notification - it now follows the attention.
        if ($unreadIds ?? []) {
            Notification::markManyRead($unreadIds, (int) $user['id']);
        }

        $this->view('wall', [
            // The merged timeline (studio updates + the member's
            // notifications), newest first.
            'items'         => $items,
            'posts'         => $posts,
            // The comments of every post in ONE query. The view used to
            // call forEntity() once per post - 20 queries for a 20-post
            // feed.
            'postComments'  => $postComments,
            'notifications' => $notifications,
            'unreadIds'     => $unreadIds ?? [],
            'sidebarNav'    => true,
            'paginator'     => ['page' => $page, 'pages' => $pages, 'total' => $total],
            'baseUrl'       => url('/wall'),
        ]);
    }

    /**
     * The merged timeline: the studio's updates and the member's own
     * notifications, newest first.
     *
     * A notification that points at a gallery whose studio post is already
     * in this page is DROPPED: the post says the same thing better (it
     * carries the comments as well), and the merged feed would otherwise
     * show the same set twice - the studio announces a set, and the
     * notification is the member's copy of that announcement.
     *
     * Every item has the same shape, so the page renders ONE card for both
     * kinds (views/partials/wall_post.php).
     *
     * @param array $posts         the studio posts on this page
     * @param array $notifications the member's notifications (page 1 only)
     * @param array $postComments  the comment count per post id
     * @return array<int, array<string, mixed>>
     */
    private function buildFeed(array $posts, array $notifications, array $postComments): array
    {
        $postGalleryIds = [];
        foreach ($posts as $post) {
            $galleryId = (int) ($post['gallery_id'] ?? 0);
            if ($galleryId > 0) {
                $postGalleryIds[$galleryId] = $galleryId;
            }
        }

        $items = [];

        foreach ($posts as $post) {
            $preview = $post['preview'] ?? null;
            $postId  = (int) $post['id'];
            $items[] = [
                'kind'       => 'post',
                'created_at' => (string) ($post['created_at'] ?? ''),
                'age'        => (int) ($post['age_seconds'] ?? 0),
                'pinned'     => !empty($post['pinned']),
                'badge'      => $preview !== null ? 'New set' : 'Studio update',
                'tone'       => $preview !== null ? 'gallery' : 'default',
                'title'      => $preview !== null ? (string) ($preview['title'] ?? '') : '',
                'body'       => $preview === null ? (string) ($post['body'] ?? '') : '',
                'url'        => $preview !== null
                    ? url('/galleries/' . (int) $preview['gallery_id'])
                    : url('/wall'),
                'preview'    => $preview,
                'locked'     => $preview !== null && empty($preview['can_view']),
                'count'      => (int) ($preview['count'] ?? 0),
                'comments'   => count($postComments[(int) $postId] ?? []),
                'postId'     => $postId,
                'isNew'      => false,
            ];
        }

        foreach ($notifications as $notification) {
            $preview = $notification['preview'] ?? null;
            $galleryId = (int) ($preview['gallery_id'] ?? 0);

            // The studio post for this set is already on the page.
            if ($galleryId > 0 && isset($postGalleryIds[$galleryId])) {
                continue;
            }

            $type = (string) ($notification['type'] ?? '');
            [$badge, $tone] = match ($type) {
                'gallery'  => ['New set', 'gallery'],
                'wall'     => ['Wall post', 'default'],
                'reply'    => ['Reply', 'reply'],
                'purchase' => ['Membership', 'purchase'],
                default    => ['Update', 'default'],
            };

            $items[] = [
                'kind'       => 'notification',
                'created_at' => (string) ($notification['created_at'] ?? ''),
                'age'        => (int) ($notification['age_seconds'] ?? 0),
                'pinned'     => false,
                'badge'      => $badge,
                'tone'       => $tone,
                'title'      => $preview !== null
                    ? (string) ($preview['title'] ?? '')
                    : (string) ($notification['title'] ?? ''),
                'body'       => $preview !== null
                    ? (string) ($preview['description'] ?? '')
                    : (string) ($notification['body'] ?? ''),
                'url'        => url('/notifications/' . (int) $notification['id']),
                'preview'    => $preview,
                'locked'     => $preview !== null && empty($preview['can_view']),
                'count'      => (int) ($preview['count'] ?? 0),
                'comments'   => 0,
                'postId'     => null,
                'isNew'      => $notification['read_at'] === null,
            ];
        }

        // Pinned first, then newest first. The two sources share one
        // clock, so a plain string compare on the timestamp is enough.
        usort($items, static function (array $a, array $b): int {
            if ($a['pinned'] !== $b['pinned']) {
                return $a['pinned'] ? -1 : 1;
            }

            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return $items;
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

            $minLevel = \App\Models\Gallery::effectiveLevel($gallery);

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