<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\WallPost;

/**
 * The member Wall: notifications for the signed-in member followed by creator
 * posts, newest first (pinned on top), each with its comment thread. Posting
 * is creator-only (admin Engagement page); members read, get notified and
 * comment.
 */
class WallController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();

        $user  = Auth::user();
        $posts = WallPost::allPublished(30);

        foreach ($posts as &$post) {
            $post['comment_count'] = Comment::countFor(Comment::TYPE_WALL, (int) $post['id']);
        }
        unset($post);

        $notifications = Notification::forUser((int) $user['id'], 30);
        $unreadIds     = [];
        foreach ($notifications as $n) {
            if ($n['read_at'] === null) {
                $unreadIds[] = (int) $n['id'];
            }
        }

        $this->view('wall', [
            'posts'         => $posts,
            'notifications' => $notifications,
            'unreadIds'     => $unreadIds,
            'sidebarNav'    => true,
        ]);
    }
}