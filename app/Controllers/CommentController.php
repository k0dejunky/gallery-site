<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Comment;
use App\Models\Gallery;
use App\Models\Notification;
use App\Models\Photo;
use App\Models\WallPost;

/**
 * Member comments on galleries and wall posts. Posting requires login; the
 * prior comment author on the same thread gets an in-app notification.
 */
class CommentController extends Controller
{
    public function store(): void
    {
        Auth::requireLogin();

        $user   = Auth::user();
        $type   = (string) $this->request->post('commentable_type', '');
        $target = (int) $this->request->post('commentable_id', '0');
        $body   = trim((string) $this->request->post('body', ''));

        $back = $this->request->header('HTTP_REFERER') ?: '/';

        if (!in_array($type, [Comment::TYPE_GALLERY, Comment::TYPE_WALL, Comment::TYPE_PHOTO], true)) {
            $this->flash('error', 'Invalid comment target.');
            $this->redirect($back);
            return;
        }

        if ($target <= 0) {
            $this->flash('error', 'Invalid comment target.');
            $this->redirect($back);
            return;
        }

        if ($type === Comment::TYPE_GALLERY) {
            if (Gallery::find($target) === null) {
                $this->notFound();
                return;
            }
        } elseif ($type === Comment::TYPE_PHOTO) {
            $photo = Photo::find($target);
            if ($photo === null) {
                $this->notFound();
                return;
            }
        } else {
            $post = WallPost::find($target);
            if ($post === null) {
                $this->notFound();
                return;
            }
        }

        if ($body === '') {
            $this->flash('error', 'Please write a comment before posting.');
            $this->redirect($back);
            return;
        }

        if (mb_strlen($body) > 2000) {
            $this->flash('error', 'Comments are limited to 2000 characters.');
            $this->redirect($back);
            return;
        }

        $id = Comment::add((int) $user['id'], $type, $target, $body);

        // Notify the previous author on this thread (only when they are a
        // different person) so replies surface as an in-app notification.
        // A gallery's thread also includes the comments on wall posts that
        // promote it, so a reply there reaches either author.
        $prior = $type === Comment::TYPE_GALLERY
            ? Comment::previousAuthorForGallery($target, (int) $user['id'])
            : Comment::previousAuthor($type, $target, (int) $user['id']);
        if ($prior !== null && (int) $prior['user_id'] !== (int) $user['id']) {
            $url = match ($type) {
                Comment::TYPE_GALLERY => '/galleries/' . $target,
                Comment::TYPE_PHOTO   => (is_video((string) $photo['filename']) ? '/videos/' : '/images/') . $target,
                default               => '/wall#comment-' . $id,
            };

            Notification::add(
                (int) $prior['user_id'],
                'reply',
                mb_substr($body, 0, 60),
                'Someone replied to a comment you left.',
                $url
            );
        }

        $this->flash('success', 'Comment posted.');
        $this->redirect($back . '#comment-' . $id);
    }
}