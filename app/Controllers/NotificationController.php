<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;

/**
 * Member notification hub: the unread badge in the nav and per-item "read
 * and go". The inbox itself now lives on the member Wall, so the standalone
 * index redirects there.
 */
class NotificationController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $this->redirect('/wall');
    }

    /** Mark one notification read and jump to its target. */
    public function show(int $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $item = Notification::forUserOne((int) $user['id'], $id);
        if ($item === null) {
            $this->redirect('/notifications');
            return;
        }

        Notification::markRead($id, (int) $user['id']);
        $this->redirect($item['url'] !== '' ? $item['url'] : '/notifications');
    }

    public function readAll(): void
    {
        Auth::requireLogin();
        Notification::markAllRead((int) Auth::user()['id']);

        $this->flash('success', 'All notifications marked as read.');
        $this->redirect('/wall');
    }
}