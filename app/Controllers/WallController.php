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
        $posts = WallPost::allPublished(30);

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
            $post['comment_count'] = Comment::countFor(Comment::TYPE_WALL, (int) $post['id']);

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

        $this->view('wall', [
            'posts'         => $posts,
            'notifications' => $notifications,
            'unreadIds'     => $unreadIds ?? [],
            'sidebarNav'    => true,
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
                    || \App\Models\Purchase::userUnlocked($userId, $galleryId),
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