<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Comment;
use App\Models\WallPost;

/**
 * The member Wall: creator posts, newest first (pinned on top), each with its
 * comment thread. Posting is creator-only (admin Engagement page); members
 * read and comment.
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

        $this->view('wall', [
            'posts'       => $posts,
            'sidebarNav'  => true,
        ]);
    }
}